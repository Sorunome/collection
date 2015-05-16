(function($){
	var ADMIN = false,
		ls = (function(){
			var self = {
					getCookie:function(c_name){
						var i,x,y,ARRcookies=document.cookie.split(";");
						for(i=0;i<ARRcookies.length;i++){
							x=ARRcookies[i].substr(0,ARRcookies[i].indexOf("="));
							y=ARRcookies[i].substr(ARRcookies[i].indexOf("=")+1);
							x=x.replace(/^\s+|\s+$/g,"");
							if(x==c_name){
								return unescape(y);
							}
						}
					},
					setCookie:function(c_name,value,exdays){
						var exdate = new Date(),
							c_value = escape(value);
						exdate.setDate(exdate.getDate() + exdays);
						c_value += ((exdays===null) ? '' : '; expires='+exdate.toUTCString());
						document.cookie=c_name + '=' + c_value;
					},
					support:function(){
						try{
							return 'localStorage' in window && window['localStorage'] !== null;
						}catch(e){
							return false;
						}
					}
				};
			return {
				get:function(name){
					if(self.support()){
						return localStorage.getItem(name);
					}
					return self.getCookie(name);
				},
				set:function(name,value){
					if(self.support()){
						localStorage.setItem(name,value);
					}else{
						self.setCookie(name,value,30);
					}
				}
			};
		})(),
		network = (function(){
			var self = {
					errors:[],
					warnings:[],
					errorsOpen:false,
					warningsOpen:false,
					addError:function(s,e){
						self.errors.push({
							time:(new Date().getTime()),
							file:s,
							content:e
						});
						$('#errors')
							.css('display','')
							.find('.count')
							.text(self.errors.length);
						$('#icons').css('display','');
					},
					addWarning:function(s,e){
						self.warnings.push({
							time:(new Date().getTime()),
							file:s,
							content:e
						});
						$('#warnings')
							.css('display','')
							.find('.count')
							.text(self.warnings.length);
						$('#icons').css('display','');
					},
					handleSignals:function(s,data){
						if(data.errors!==undefined){
							$.each(data.errors,function(i,e){
								if(e.type!==undefined){
									self.addError(s,e);
								}else{
									self.addError(s,{
										type:'misc',
										message:e
									});
								}
								if(self.errorsOpen){
									$('.errors > .errorPopupCont').append(self.getSinglePopupEntry(self.errors[self.errors.length - 1]));
								}
							});
						}
						if(data.warnings!==undefined){
							$.each(data.warnings,function(i,w){
								if(w.type!==undefined){
									self.addWarning(s,w);
								}else{
									self.addWarning(s,{
										type:'misc',
										message:w
									});
								}
								if(self.warningsOpen){
									$('.warnings > .errorPopupCont').append(self.getSinglePopupEntry(self.warnings[self.warnings.length - 1]));
								}
							});
						}
						if(data.sqlqueries!==undefined){
							$('#queryNum').text(sprintf(locale.get('queryMsg'),data.sqlqueries));
						}
						if(data.isLoggedIn!==undefined){
							if(data.isLoggedIn){
								$('#navLogin').css('display','none');
								$('#navLogout').css('display','');
								if(data.isAdmin){
									$('#navAdmin').css('display','');
									ADMIN = true;
								}else{
									$('#navAdmin').css('display','none');
									ADMIN = false;
								}
							}else{
								$('#navLogin').css('display','');
								$('#navAdmin,#navLogout').css('display','none');
							}
						}
					},
					getSinglePopupEntry:function(e){
						return $('<div>')
							.css('border-bottom','1px solid black')
							.append(
								'Time: ',
								(new Date(e.time)).toLocaleTimeString(),
								'<br>File: ',
								$('<span>').text(e.file).html(),
								$.map(e.content,function(val,i){
									return ['<br>',$('<span>').text(i).html(),': ',$('<span>').text(val).html()];
								})
							);
					},
					makePopup:function(type,data,fn){
						return $('<div>')
							.addClass('errorPopup')
							.addClass(type.toLowerCase())
							.append(
								$('<a>')
									.text('Close')
									.click(function(e){
										e.preventDefault();
										$(this).parent().remove();
										fn();
									})
									.css({
										cursor:'pointer',
										color:'blue'
									}),
								'&nbsp;',
								$('<b>')
									.text(type),
								$('<div>')
									.addClass('errorPopupCont')
									.append(
										$.map(data,function(e){
											return self.getSinglePopupEntry(e);
										})
									)
							)
							.appendTo('body');
					}
				};
			return {
				getJSON:function(s,fn){
					return $.getJSON(s)
						.done(function(data){
							self.handleSignals(s,data);
							if(fn!==undefined){
								fn(data);
							}
						});
				},
				post:function(s,d,fn){
					return $.post(s,d)
						.done(function(data){
							self.handleSignals(s,data);
							if(fn!==undefined){
								fn(data);
							}
						});
				},
				init:function(){
					$('#errors > .icon')
						.click(function(){
							if(!self.errorsOpen){
								self.errorsOpen = true;
								self.makePopup('Errors',self.errors,function(){
									self.errorsOpen = false;
								});
							}
						});
					$('#warnings > .icon')
						.click(function(){
							if(!self.warningsOpen){
								self.warningsOpen = true;
								self.makePopup('Warnings',self.warnings,function(){
									self.warningsOpen = false;
								});
							}
						});
				}
			};
		})(),
		filehandler = (function(){
			self = {
					callback:function(){},
					init:function(){
						$('#uploader').attr('id','upload').text('Upload').uploadFile({
							url:'server.php?upload',
							fileName:'file',
							returnType:'json',
							customErrorKeyStr:'upload-error',
							onSuccess:self.onSuccess,
							showStatusAfterSuccess:false
						});
						$('#uploadForm_close').click(function(e){
							$('#uploadForm').css('display','none');
						});
						$('#listFiles_close').click(function(e){
							$('#listFiles').css('display','none');
						});
					},
					setCallback:function(fn){
						self.callback = fn;
					},
					onSuccess:function(fileArray,data,xhr,pd){
						self.callback(fileArray,data,xhr,pd);
					}
				};
			return {
					init:function(){
						self.init();
					},
					setCallback:function(fn){
						self.setCallback(fn);
					}
				};
		})(),
		hist = (function(){
			var self = {
					doPush:false,
					getIdFromURL:function(){
						if(document.URL.split('?')[1]!==undefined){
							return document.URL.split('?')[1].split('#')[0];
						}else{
							return 'c1';
						}
					},
					load:function(id){
						switch(id[0]){
							case 'a': // admin
								page.showAdminPage();
								break;
							case 'c': // kategorie
								categories.load(id.substr(1));
								break;
							case 'o': // objekt
								if(id[1]=='e'){
									obj.edit(id.substr(2));
								}else if(id[1]=='t'){ // object types
									if(id[2]=='e'){
										objectTypes.edit(id.substr(3));
									}else{
										objectTypes.list();
									}
								}else{
									obj.display(id.substr(1));
								}
								break;
							case 'v':
								if(id[1]=='t'){ // variable types
									if(id[2]=='e'){
										variableTypes.edit(id.substr(3));
									}else{
										variableTypes.list();
									}
								}
								break;
						}
					},
					push:function(id){
						if(history.pushState && self.doPush){
							history.pushState({},id,document.URL.split('?')[0].split('#')[0]+'?'+id);
						}else{
							self.doPush = true;
						}
					},
					pop:function(){
						self.doPush = false;
						self.load(self.getIdFromURL());
					},
					init:function(){
						if(history.pushState){
							$(window).bind('popstate',function(e){
								self.pop();
							});
						}
					}
				};
			return {
					load:function(id){
						self.load(id);
					},
					push:function(id){
						self.push(id);
					},
					init:function(){
						self.init();
					}
				};
		})(),
		locale = (function(){
			var self = {
					available:['en','de'],
					dict:{},
					load:function(s,fn){
						if(self.available.indexOf(s) > -1){
							network.getJSON('locale_'+s+'.json',function(data){
								self.dict = data;
								if(fn!==undefined){
									fn();
								}
							});
						}
					},
					get:function(s){
						return self.dict[s];
					}
				};
			return {
					load:function(s,fn){
						self.load(s,fn);
					},
					get:function(s){
						return self.get(s);
					}
				};
		})(),
		variableTypes = (function(){
			var self = {
					save:function(varType,fn){
						network.post('server.php?saveVarType='+varType.id,{
							name:varType.name,
							tooltip:varType.tooltip,
							value:JSON.stringify(varType.value)
						},function(data){
							fn(data);
						});
					},
					getEditForm:function(id){
						page.hideAll();
						network.getJSON('server.php?editVarType='+id,function(data){
							var varType = data.variableType;
							$specificForm = $('<span>');
							switch(varType.type){
								case 0:
									$specificForm = $('<span>').append(
										'Min:',
										$('<input>').attr('type','number').val(varType.value.min).change(function(){varType.value.min = parseInt(this.value,10);self.save(varType);}),
										'<br>Max:',
										$('<input>').attr('type','number').val(varType.value.max).change(function(){varType.value.max = parseInt(this.value,10);self.save(varType);}),
										'<br>Prefix:',
										$('<input>').attr('type','text').val(varType.value.prefix).change(function(){varType.value.prefix = this.value;self.save(varType);}),
										'<br>Suffix:',
										$('<input>').attr('type','text').val(varType.value.suffix).change(function(){varType.value.suffix = this.value;self.save(varType);})
									);
									break;
								case 2:
									$specificForm = $('<span>').append(
										$.map(varType.value,function(v,i){
											return [
												$('<input>').attr('type','text').val(v.value),
												$('<a>').attr('href','http://up').text('^').click(function(e){
													e.preventDefault();
													if(i!=0){
														var temp = varType.value[i-1];
														varType.value[i-1] = varType.value[i];
														varType.value[i] = temp;
														self.save(varType,function(){
															self.getEditForm(id);
														});
													}
												}),
												' ',
												$('<a>').attr('href','http://down').text('v').click(function(e){
													e.preventDefault();
													if(i+1<varType.value.length){
														var temp = varType.value[i+1];
														varType.value[i+1] = varType.value[i];
														varType.value[i] = temp;
														self.save(varType,function(){
															self.getEditForm(id);
														});
													}
												}),
												' ',
												$('<a>').attr('href','http://delete').text('x').click(function(e){
													e.preventDefault();
													varType.value.splice(i,1);
													self.save(varType,function(){
														self.getEditForm(id);
													});
												}),
												,'<br>'];
										}),
										$('<a>').attr('href','http://new').text(locale.get('newPickListItem')).click(function(e){
											e.preventDefault();
											var name = prompt(locale.get('newPickListItemMsg'),'');
											if(name!==null){
												network.post('server.php?addPicklistItem='+id,{value:name},function(data){
													self.getEditForm(id);
												});
											}
										})
									);
									break;
								case 4:
									$specificForm = $('<span>').append(
										'Pattern:',
										$('<input>').attr('type','text').val(varType.value.pattern).change(function(){varType.value.pattern = this.value;self.save(varType);}),
										'<br>Replace:',
										$('<input>').attr('type','text').val(varType.value.replace).change(function(){varType.value.replace = this.value;self.save(varType);})
									);
									break;
							}
							$('#adminPage').empty().append(
								$('<a>').attr('href','http://back').text(locale.get('back')).click(function(e){
									e.preventDefault();
									variableTypes.list();
								}),
								'<br>'+locale.get('varTypeName')+':',
								$('<input>').attr('type','text').val(varType.name).change(function(){varType.name = this.value;self.save(varType);}),
								'<br>'+locale.get('varTypeTooltip')+':',
								$('<input>').attr('type','text').val(varType.tooltip).change(function(){varType.tooltip = this.value;self.save(varType);}),
								'<br>',
								$specificForm
							);
							page.show('adminPage');
							hist.push('vte'+id.toString(10));
						});
					},
					list:function(){
						page.hideAll();
						network.getJSON('server.php?listVarTypes',function(data){
							$('#adminPage').empty().append(
								$('<table>').append(
									$('<tr>').append(
										$('<th>').text(locale.get('varTypeName')),
										$('<th>').text(locale.get('varTypeType')),
										$('<th>').text(locale.get('varTypeTooltip')),
										$('<th>').text(' ')
									),
									$.map(data.variableTypes,function(v){
										var type = locale.get('varTypeUnkown');
										switch(v.type){
											case 0:
												type = locale.get('varTypeInt');
												break;
											case 1:
												type = locale.get('varTypeText');
												break;
											case 2:
												type = locale.get('varTypePicklist');
												break;
											case 3:
												type = locale.get('varTypeImage');
												break;
											case 4:
												type = locale.get('varTypeRegex');
												break;
										}
										return $('<tr>').append(
												$('<td>').text(v.name),
												$('<td>').text(type),
												$('<td>').text(v.tooltip),
												$('<td>').append(
														$('<a>')
															.attr('href','http://edit')
															.text(locale.get('editVarType'))
															.click(function(e){
																e.preventDefault();
																self.getEditForm(v.id);
															})
													)
											);
									})
								)
							);
							page.show('adminPage');
							hist.push('vt');
						});
					}
				};
			return {
					list:function(){
						self.list();
					},
					edit:function(id){
						self.getEditForm(id);
					}
				};
		})(),
		vars = (function(){
			var self = {
					saveVar:function(id,value,fn){
						network.post('server.php?saveVar='+id,{value:value},function(data){
							if(fn!==undefined){
								fn(data);
							}
						});
					},
					getEditForm:function(v){
						switch(v.type){
							case 0: //int
								return [v.construct.prefix,$('<input>')
									.attr('type','number')
									.attr(((v.construct.max < v.construct.min)?'false':'min'),v.construct.min)
									.attr(((v.construct.max < v.construct.min)?'false':'max'),v.construct.max)
									.val(v.value).change(function(){
										self.saveVar(v.id,this.value,function(){
											if(!data.success){
												alert(locale.get("errorSaveVar"));
											}
										});
									}),v.construct.suffix];
							case 1: //text
								return $('<textarea>').val(v.value).change(function(){
										self.saveVar(v.id,this.value,function(){
											if(!data.success){
												alert(locale.get("errorSaveVar"));
											}
										});
									});
							case 2: //picklist
								return $('<select>').append(
										$('<option>').attr('value','-1').attr((v.value==='' || v.value == -1?'selected':'false'),'selected'),
										$.map(v.construct,function(cv){
											return $('<option>')
												.attr('value',cv.id)
												.attr((cv.id===parseInt(v.value,10)?'selected':'false'),'selected')
												.text(cv.value)
										}),
										$('<option>').attr('value','new').text(locale.get('newPickListItem'))
									)
									.change(function(){
										if(this.value!='new'){
											self.saveVar(v.id,this.value);
											v.value = this.value;
										}else{
											var name = prompt(locale.get('newPickListItemMsg'),''),
												_this = this;
											if(name===null){
												$(this).val(v.value);
											}else{
												network.post('server.php?addPicklistItem='+v.varTypeId,{value:name},function(data){
													$(_this).find('[value="new"]').before(
														$('<option>').attr({
															'value':data.id,
															'selected':'selected'
														}).text(data.name)
													);
													$(_this).val(data.id);
													self.saveVar(v.id,data.id,function(){
														if(!data.success){
															alert(locale.get("errorSaveVar"));
														}
													});
													v.value = data.id;
												});
											}
										}
									});
							case 3: //bild
								var $input = $('<input>').attr('type','text').val(v.value).change(function(e){
									self.saveVar(v.id,this.value,function(){
										if(!data.success){
											alert(locale.get("errorSaveVar"));
										}
									});
								});
								return [
										$input,
										'<br>',
										$('<a>').attr('href','http://upload').text(locale.get('uploadImage')).click(function(e){
											e.preventDefault();
											filehandler.setCallback(function(fileArray,resp){
												$input.val('uploads/'+resp.files[0]);
												self.saveVar(v.id,$input.val(),function(){
													if(!data.success){
														alert(locale.get("errorSaveVar"));
													}
												});
												$('#uploadForm').css('display','none');
											});
											$('#uploadForm').css('display','block');
										}),
										'<br>',
										$('<a>').attr('href','http://existing').text(locale.get('existingImage')).click(function(e){
											e.preventDefault();
											network.getJSON('server.php?listFiles',function(data2){
												$('#listFiles_real').empty().append(
													$.map(data2.files,function(f){
														return [
															(f.type=='img'?
																$('<span>').text(f.name).addClass('listFiles_item').mouseover(function(e){
																	$('#listFiles_preview').attr('src','uploads/'+f.name).css('display','block');
																}).mouseout(function(e){
																	$('#listFiles_preview').css('display','none');
																}).click(function(e){
																	$input.val('uploads/'+f.name);
																	self.saveVar(v.id,$input.val());
																	$('#listFiles').css('display','none');
																})
															:
																$('<span>').text(f.name).addClass('listFiles_item').click(function(e){
																	$input.val('uploads/'+f.name);
																	self.saveVar(v.id,$input.val(),function(){
																		if(!data.success){
																			alert(locale.get("errorSaveVar"));
																		}
																	});
																	$('#listFiles').css('display','none');
																})
															),
															'<br>'
														];
													})
												);
												$('#listFiles').css('display','block');
											});
										})
									];
							case 4: //regex
								return $('<input>').attr('type','text').val(v.value).keyup(function(){
										var _this = this;
										console.log(this.value);
										self.saveVar(v.id,this.value,function(data){
											if(data.success!==true){
												$(_this).addClass('invalid');
											}else{
												$(_this).removeClass('invalid');
											}
										});
									});
								break;
							case 5: //time
								return $('<input>').attr('type','text').val(v.value).change(function(){
										self.saveVar(v.id,this.value,function(){
											if(!data.success){
												alert(locale.get("errorSaveVar"));
											}
										});
									});
						}
					}
				};
			return {
					getEditForm:function(v){
						return self.getEditForm(v);
					}
				};
		})(),
		objectTypes = (function(){
			var self = {
					save:function(objType,fn){
						network.post('server.php?saveObjType='+objType.id,{
							name:objType.name,
							value:JSON.stringify(objType.value)
						},fn);
					},
					getEditForm:function(id){
						page.hideAll();
						network.getJSON('server.php?editObjType='+id,function(data){
							var objType = data.objectType,
								varTypes = data.variableTypes;
							$('#adminPage').empty().append(
								$('<a>').attr('href','http://back').text(locale.get('back')).click(function(e){
									e.preventDefault();
									objectTypes.list();
								}),
								'<br>'+locale.get('objTypeName')+':',
								$('<input>').attr('type','text').val(objType.name).change(function(){objType.name = this.value;self.save(objType);}),
								'<br>',
								$.map(objType.value,function(o,i){
									return $('<span>')
										.css({
											'display':'inline-block',
											'border':'1px solid black',
											'padding':'5px'
										})
										.append(
											$('<a>').attr('href','http://up').text('<').click(function(e){
												e.preventDefault();
												if(i!=0){
													var temp = objType.value[i-1];
													objType.value[i-1] = objType.value[i];
													objType.value[i] = temp;
													self.save(objType,function(){
														self.getEditForm(id);
													});
												}
											}),
											' ',
											$('<a>').attr('href','http://down').text('>').click(function(e){
												e.preventDefault();
												if(i+1<objType.value.length){
													var temp = objType.value[i+1];
													objType.value[i+1] = objType.value[i];
													objType.value[i] = temp;
													self.save(objType,function(){
														self.getEditForm(id);
													});
												}
											}),
											' ',
											$('<a>').attr('href','http://delete').text('x').click(function(e){
												e.preventDefault();
												objType.value.splice(i,1);
												self.save(objType,function(){
													self.getEditForm(id);
												});
											}),
											'<br>'+locale.get('objTypePropName')+':',
											$('<input>').attr('type','text').val(o.name).change(function(){objType.value[i].name = this.value;self.save(objType);}),
											'<br>'+locale.get('objTypePropVarType')+':',
											$('<select>').append(
												$.map(varTypes,function(vtv,vti){
													return $('<option>').text(vtv).val(vti);
												})
											).val(o.varType).change(function(){objType.value[i].varType = parseInt(this.value,10);self.save(objType);}),
											'<br>'+locale.get('objTypePropQuick')+':',
											$('<input>').attr('type','checkbox').attr((o.quick?'checked':'false'),'checked').change(function(){objType.value[i].quick = this.checked;self.save(objType);})
										)
								}),
								$('<select>').append(
									$('<option>').text(locale.get('objTypeAddProp')).val(-1),
									$.map(varTypes,function(vtv,vti){
										return $('<option>').text(vtv).val(vti);
									})
								).change(function(){
									var name = prompt(locale.get('objTypeAddPropMsg')),
										maxId = 1;
									if(name!='' && name!=undefined){
										$.map(objType.value,function(otv){
											if(otv.id > maxId){
												maxId = otv.id;
											}
										})
										objType.value.push({
											'id':maxId+1,
											'name':name,
											'varType':parseInt(this.value,10),
											'quick':false
										});
										self.save(objType,function(){
											self.getEditForm(id);
										});
									}else{
										$(this).val(-1);
									}
								})
							);
							page.show('adminPage');
							hist.push('ote'+id.toString(10));
						});
					},
					list:function(){
						page.hideAll();
						network.getJSON('server.php?listObjTypes',function(data){
							$('#adminPage').empty().append(
								$('<table>').append(
									$('<tr>').append(
										$('<th>').text(locale.get('objTypeName')),
										$('<th>').text(' ')
									),
									$.map(data.objectTypes,function(o){
										return $('<tr>').append(
												$('<td>').text(o.name),
												$('<td>').append(
													$('<a>').attr('href','http://edit').text(locale.get('editObjType')).click(function(e){
														e.preventDefault();
														self.getEditForm(o.id);
													})
												)
											);
									})
								)
							);
							page.show('adminPage');
							hist.push('ot');
						});
					}
				};
			return {
					list:function(){
						self.list();
					},
					edit:function(id){
						self.getEditForm(id);
					}
				};
		})(),
		obj = (function(){
			var self = {
					edit:function(id){
						page.hideAll();
						network.getJSON('server.php?editObj='+id.toString(10),function(data){
							$('#editPage').empty().append(
									$('<a>')
										.attr('href','http://back')
										.click(function(e){
											e.preventDefault();
											obj.display(id);
										})
										.text(locale.get('backToObj')),
									'<br>',
									$('<table>').append(
										$('<tr>').append(
											$.map(data.objectInfo,function(v){
												return $('<th>').text(v.name);
											})
										),
										$('<tr>').append(
											$.map(data.objectInfo,function(v){
												return $('<td>').html(vars.getEditForm(v));
											})
										)
									)
								);
							page.show('editPage');
							hist.push('oe'+id.toString(10));
						});
					},
					delete:function(id){
						if(confirm(locale.get('deleteObjMsg'))){
							page.hideAll();
							network.getJSON('server.php?delObj='+id.toString(10),function(data){
								categories.load(data.goto);
							})
						}
					},
					display:function(id){
						page.hideAll();
						network.getJSON('server.php?obj='+id.toString(10),function(data){
							if(data.object!==undefined){
								$('#objectPage').empty().append(
										categories.getCatTree(data.cattree),
										$('<div>').append(
												(ADMIN?[
													$('<a>')
														.attr('href','http://edit')
														.text(locale.get('editObj'))
														.click(function(e){
															e.preventDefault();
															self.edit(id);
														}),
													'&nbsp;',
													$('<a>')
														.attr('href','http://delete')
														.text(locale.get('deleteObj'))
														.click(function(e){
															e.preventDefault();
															self.delete(id);
														}),
													'&nbsp;'
												]:''),
												$('<a>')
													.attr('href','server.php?objcsv='+id.toString(10))
													.text(locale.get('exportCSV'))
											),
										obj.makeTable([data.object])
									);
								categories.set(data.categories,'o'+id.toString(),data.primcat);
							}else{
								$('#objectPage').empy().text(locale.get('errObjNotFound'));
							}
							page.show('objectPage');
							hist.push('o'+id.toString(10));
						});
					},
					makeTable:function(o,editLink,quick){
						var editLinkI = 0;
						return $('<table>')
							.addClass('objectTable')
							.append(
								$.map(o,function(v,i){
									return [(i===0?
											$('<tr>').append(
													$.map(v,function(ov,oi){
														if(oi!=='id'){
															if((quick && ov.quick) || !quick){
																return $('<th>').text(oi);
															}
														}else{
															editLinkI = oi;
															if(editLink){
																return $('<th>').text('');
															}
														}
													})
												)
										:''),
											$('<tr>').append(
													$.map(v,function(ov,oi){
														if(oi!==editLinkI){
															if((quick && ov.quick) || !quick){
																return $('<td>').html(ov.value);
															}
															return undefined;
														}else if(editLink){
															return $('<td>').append(
																	$('<a>')
																		.attr('href','http://view')
																		.click(function(e){
																			e.preventDefault();
																			obj.display(ov);
																		})
																		.text(locale.get('viewObj'))
																);
														}
													})
												)
										]
								})
							);
					},
					new:function(objType,cats){
						network.getJSON('server.php?newObj='+objType+'&cats='+JSON.stringify(cats),function(data){
							self.edit(data.objId);
						});
					}
				};
			return {
					display:function(id){
						self.display(id);
					},
					makeTable:function(o,editLink,quick){
						return self.makeTable(o,editLink,quick);
					},
					new:function(objType,cats){
						self.new(objType,cats);
					},
					edit:function(id){
						self.edit(id);
					}
				};
		})(),
		categories = (function(){
			var self = {
					makeCategoryList:function(c,extra,id,primcat){
						console.log(primcat);
						return $('<ul>')
								.addClass('categoryList')
								.append(
									$.map(c,function(v){
										console.log(v);
										return $('<li>').append(
												$('<a>')
													.text(v.name)
													.attr('href','http://LoadCategory')
													.click(function(e){
														e.preventDefault();
														categories.load(v.id);
													}),
												(extra && ADMIN?
													['&nbsp;',
													$('<input>')
														.attr({
															type:'radio',
															name:'primcat'
														})
														.addClass('categoryEditButton')
														.css('display','none')
														.attr((v.id == primcat?'checked':'false'),'checked')
														.val(v.id)
														.mouseup(function(){
															var _self = this;
															network.getJSON('server.php?primCat='+encodeURI(this.value)+'&id='+encodeURI(id),function(data){
																if(!data.success){
																	alert(locale.get('genericError'));
																}
															});
														}),
													$('<span>')
														.addClass('categoryRemoveButton')
														.addClass('categoryEditButton')
														.css('display','none')
														.text('X')
														.click(function(e){
															e.preventDefault();
															network.getJSON('server.php?remCat='+encodeURI(v.id)+'&id='+encodeURI(id),function(data){
																if(data.success){
																	categories.set(data.categories,id,data.primcat);
																}
															});
														})
												]:'')
											)
									})
								);
					},
					clearAddForm:function(elem){
						$(elem).parent().find('input').val('');
						$(elem).parent().find('.catHint').empty().css('display','none');
						$(elem).parent().css('display','none');
						$(elem).parent().prev().css('display','inline');
					},
					editName:function(id,oldname){
						var name = prompt(sprintf(locale.get('editCatMsg'),oldname));
						if(name!='' && name){
							network.getJSON('server.php?catname='+id.toString(10)+'&name='+encodeURIComponent(name),function(data){
								if(!data.duplicate){
									self.load(id);
								}else{
									alert(locale.get('duplicateCatNameMsg'));
								}
							});
						}
					},
					getCatTree:function(tree){
						var first = true;
						return $('<div>').addClass('cattree').append(
								$.map(tree,function(t){
									if(t.name!==null){
										var add = (!first?' > ':'');
										first = false;
										return [
												add,
												$('<a>')
													.text(t.name)
													.attr('href','http://LoadCategory')
													.click(function(e){
														e.preventDefault();
														categories.load(t.id);
													})
											];
									}
								})
							);
					},
					load:function(id){
						id = parseInt(id,10);
						page.hideAll();
						network.getJSON('server.php?cat='+id,function(data){
							$('#categoryPage > .objects').empty().append(
									self.getCatTree(data.cattree),
									$('<span>')
										.addClass('catName')
										.text(data.name),
									$('<span>')
										.addClass('catButtons')
										.append(
											'(',
											(ADMIN?[
												$('<a>')
													.text(locale.get('editCat'))
													.attr('href','http://edit')
													.click(function(e){
														e.preventDefault();
														self.editName(id,$('.catName').text());
													}),
												' | ',
												$('<a>')
													.text(locale.get('deleteCat'))
													.attr('href','http://delete')
													.addClass('delCatButton')
													.attr('data-catid',id)
													.click(function(e){
														e.preventDefault();
														if(confirm(locale.get('delCatMsg'))){
															network.getJSON('server.php?delCat='+id,function(data){
																if(data.success){
																	categories.load(1);
																}
															});
														}
													}),
												$('<span>')
													.attr('data-catid',id)
													.addClass('delCatButton')
													.append(' | ')
											]:''),
											$('<a>')
												.attr('href','server.php?catcsv='+id.toString(10))
												.text(locale.get('exportCSV')),
											')'
										),
									$('<hr>'),
									$.map(data.objects,function(v,i){
										return [
											$('<h3>').text(i),
											obj.makeTable(v.objects,true,true),
											$('<span>').append(
												(ADMIN?[
													$('<a>')
														.attr('href','http://new')
														.text(sprintf(locale.get('newObj'),i))
														.click(function(e){
															e.preventDefault();
															obj.new(v.id,[id]);
														})
												]:'')
											)
										];
									})
								);
							if(data.subcategories.length>0){
								$('#categoryPage > .subcategories').empty().append(
									$('<b>').text(locale.get('subCats')),
									self.makeCategoryList(data.subcategories)
								);
							}else{
								$('#categoryPage > .subcategories').empty();
							}
							if(id!=1){
								categories.set(data.categories,'c'+id.toString(10),data.primcat);
							}
							hist.push('c'+id.toString());
							page.show('categoryPage');
						});
					},
					set:function(c,id,primcat){
						$('#categories').empty().append(
								self.makeCategoryList(c,true,id,primcat),
								(ADMIN?[
									$('<a>')
										.attr('href','http://add')
										.addClass('categoryEditButton')
										.css('display','none')
										.text(locale.get('addCategoriesMsg'))
										.click(function(e){
											e.preventDefault();
											$(this).css('display','none');
											$(this).next().css('display','inline');
										}),
									$('<span>')
										.css('display','none')
										.append(
											$('<input>')
												.attr('type','text')
												.css('width',100)
												.keyup(function(e){
													var _this = this;
													network.getJSON('server.php?getCatHint='+encodeURI(this.value),function(data){
														if(data.hintCats.length===0){
															$(_this).addClass('invalidCat');
															$(_this).parent().find('.catHint').empty().css('display','none');
														}else{
															$(_this).removeClass('invalidCat');
															if($(_this).val()!==''){
																$(_this).parent().find('.catHint').empty().css('display','inline-block').append(
																		$('<ul>').append(
																				$.map(data.hintCats,function(v){
																					return $('<li>').text(v).click(function(e){
																						$(_this).val(v).parent().find('.catHint').empty().css('display','none');
																					});
																				})
																			)
																	);
															}else{
																$(_this).parent().find('.catHint').empty().css('display','none');
															}
														}
													})
												}),
											$('<span>')
												.addClass('acceptButton')
												.click(function(e){
													e.preventDefault();
													var _this = this,
														val = $(this).parent().find('input').val(),
														addNewCategory = function(){
															network.getJSON('server.php?addCat='+encodeURI(val)+'&id='+encodeURI(id),function(data){
																if(data.success){
																	if(c.length==0){
																		network.getJSON('server.php?primCat='+encodeURI(data.id)+'&id='+encodeURI(id),function(d){
																			categories.set(data.categories,id,data.id);
																		});
																	}else{
																		categories.set(data.categories,id,primcat);
																	}
																}else{
																	self.clearAddForm(_this);
																}
															});
														};
													if(val!==''){
														if($(this).prev().hasClass('invalidCat')){
															if(confirm(locale.get('makeNewCatMsg'))){
																network.getJSON('server.php?newCat='+encodeURIComponent(val),function(data){
																	addNewCategory();
																});
															}else{
																self.clearAddForm(this);
															}
														}else{
															addNewCategory();
														}
													}else{
														self.clearAddForm(this);
													}
												}),
											$('<span>')
												.addClass('cancleButton')
												.click(function(e){
													e.preventDefault();
													self.clearAddForm(this);
												}),
											$('<div>')
												.addClass('catHint')
												.css('display','none')
										),
									$('<a>')
										.text(locale.get('editCategoriesMsg'))
										.attr('href','edit')
										.click(function(e){
											e.preventDefault();
											$('.categoryEditButton').css('display','inline');
											$(this).css('display','none')
										})
								]:'&nbsp;')
							);
						$('#categoriesCont').css('display','block');
					}
				};
			return {
					makeCategoryList:function(c,extra,id){
						return self.makeCategoryList(c,extra,id);
					},
					load:function(id){
						self.load(id);
					},
					set:function(c,id,primcat){
						self.set(c,id,primcat);
					},
					getCatTree:function(tree){
						return self.getCatTree(tree);
					}
				};
		})(),
		search = (function(){
			var self = {
					search:function(){
						var s = $('#searchForm input[type="text"]').val();
						if(s!==''){
							network.getJSON('server.php?search='+encodeURI(s),function(data){
								page.hideAll();
								$('#searchQuery').empty().text(sprintf(locale.get('searchtext'),data.search));
								$('#searchForm input[type="text"]').val('');
								if(data.objects!==undefined){
									$('#searchRes > .objects').empty().append(
										$.map(data.objects,function(v,i){
											return [
												$('<h3>').text(i),
												obj.makeTable(v.objects,true,true)
											];
										})
									);
								}
								if(data.categories.length>0){
									$('#searchRes > .cats').empty().append(
										$('<b>').text(locale.get('cats')),
										categories.makeCategoryList(data.categories)
									);
								}else{
									$('#searchRes > .cats').empty();
								}
								page.show('searchRes');
							});
						}
					},
					init:function(){
						$('#searchForm').submit(function(e){
							e.preventDefault();
							self.search();
						}).find('input[type="text"]').before(locale.get('searchMsg')).parent().find('input[type="submit"]').val(locale.get('searchSubmitButton'));
					}
				};
			return {
					init:function(){
						self.init();
					}
				};
		})(),
		page = (function(){
			var self = {
					init:function(){
						$('#loading').text(locale.get('loading'));
						$('#catHeader').text(locale.get('cats'));
						$('nav').append(
							$.map([
									{
										text:locale.get('navHome'),
										id:'navHome',
										fn:function(){
											categories.load(1);
										}
									},
									{
										text:locale.get('navAdmin'),
										id:'navAdmin',
										fn:function(){
											page.showAdminPage();
										}
									},
									{
										text:locale.get('navLogin'),
										id:'navLogin',
										fn:function(){
											self.showLoginPage();
										}
									},
									{
										text:locale.get('navLogout'),
										id:'navLogout',
										fn:function(){
											network.getJSON('server.php?logout',function(data){
												categories.load(1);
											});
										}
									}
								],function(n){
									return $('<a>')
										.attr('href','http://'+n.text)
										.append(n.text)
										.attr('id',n.id)
										.click(function(e){
											e.preventDefault();
											n.fn();
										})
								})
						);
						page.hideAll();
						
						filehandler.init();
					},
					hideAll:function(){
						$('#objectPage,#categoryPage,#categoriesCont,#editPage,#searchRes,#adminPage,#uploadForm,#listFiles').css('display','none');
						
						$('#loading').css('display','block');
					},
					show:function(s){
						$('#loading').css('display','none');
						$('#'+s).css('display','block');
					},
					showLoginPage:function(){
						page.hideAll();
						$('#adminPage').empty().append(
							$('<h1>').text(locale.get('navLogin')),
							$('<form>').append(
								locale.get('askUsername'),
								$('<input>').attr({
									type:'text',
									name:'username'
								}),'<br>',
								locale.get('askPassword'),
								$('<input>').attr({
									type:'password',
									name:'password'
								}),'<br>',
								$('<input>').attr('type','submit').val(locale.get('navLogin'))
							).submit(function(e){
								e.preventDefault();
								network.post('server.php?login='+encodeURIComponent(this.username.value),{
									password:this.password.value
								},function(data){
									if(data.success){
										categories.load(1);
									}else{
										alert(locale.get('loginIncorrect'));
									}
								});
							})
						);
						page.show('adminPage');
					},
					showAdminPage:function(){
						page.hideAll();
						network.getJSON('server.php?listObjTypes',function(data){
							if(!data.isAdmin){
								categories.load(1);
							}
							$('#adminPage').empty().append(
								$('<ul>').append(
									$.map([
											{
												text:locale.get('newCategory'),
												fn:function(){
													var name = prompt(locale.get('newCategoryNameMsg'));
													if(name!=undefined && name!=''){
														network.getJSON('server.php?newCat='+encodeURIComponent(name),function(data){
															if(!data.duplicate){
																categories.load(data.catId);
															}else{
																alert(locale.get('duplicateCatNameMsg'));
															}
														});
													}
												}
											},
											{
												text:locale.get('listVariableTypes'),
												fn:function(){
													variableTypes.list();
												}
											},
											{
												ancor:false,
												text:$('<select>').append(
														$('<option>').val(-1).text(locale.get('newVariableType')),
														$.map(['varTypeInt','varTypeText','varTypePicklist','varTypeImage','varTypeRegex'],function(v,i){
															return $('<option>').val(i).text(locale.get(v));
														})
													).change(function(){
														network.getJSON('server.php?newVarType='+this.value,function(data){
															variableTypes.edit(data.id);
														});
													})
											},
											{
												text:locale.get('listObjectTypes'),
												fn:function(){
													objectTypes.list();
												}
											},
											{
												text:locale.get('newObjType'),
												fn:function(){
													network.getJSON('server.php?newObjType',function(data){
														objectTypes.edit(data.id);
													});
												}
											},
											{
												ancor:false,
												text:$('<select>').append(
														$('<option>').val(-1).text(locale.get('newObjAdminPage')),
														$.map(data.objectTypes,function(o){
															return $('<option>').val(o.id).text(o.name);
														})
													).change(function(){
														network.getJSON('server.php?newObj='+this.value,function(d){
															obj.edit(d.objId);
														})
													})
											},
											{
												text:locale.get('dispUncategorizedCats'),
												fn:function(){
													page.hideAll();
													network.getJSON('server.php?uncatCats',function(data){
														if(data.uncatCats!==undefined){
															$('#adminPage').empty().append(
																categories.makeCategoryList(data.uncatCats)
															);
															page.show('adminPage');
														}
													});
												}
											},
											{
												text:locale.get('dispUncategorizedObjs'),
												fn:function(){
													page.hideAll();
													network.getJSON('server.php?uncatObjs',function(data){
														if(data.uncatObjs!==undefined){
															$('#adminPage').empty().append(
																obj.makeTable(data.uncatObjs,true)
															);
															page.show('adminPage');
														}
													});
												}
											},
											{
												text:locale.get('addUser'),
												fn:function(){
													page.hideAll();
													$('#adminPage').empty().append(
														$('<h1>').text(locale.get('addUser')),
														$('<form>').append(
															locale.get('askUsername'),
															$('<input>').attr({
																type:'text',
																name:'username'
															}),'<br>',
															locale.get('askPassword'),
															$('<input>').attr({
																type:'password',
																name:'password'
															}),'<br>',
															locale.get('askPassword'),
															$('<input>').attr({
																type:'password',
																name:'password2'
															}),'<br>',
															$('<input>').attr('type','submit').val(locale.get('addUser'))
														).submit(function(e){
															e.preventDefault();
															if(this.password.value == this.password2.value){
																network.post('server.php?newUser='+encodeURIComponent(this.username.value),{
																	password:this.password.value
																},function(data){
																	if(data.success){
																		page.showAdminPage();
																	}else{
																		alert('error');
																	}
																});
															}else{
																alert(locale.get('pwdDontMatch'));
															}
														})
													);
													page.show('adminPage');
												}
											},
											{
												text:locale.get('editUserPem'),
												fn:function(){
													page.hideAll();
													network.getJSON('server.php?userinfo',function(data){
														$('#adminPage').empty().append(
															$.map(data.info,function(u){
																return [
																	$('<b>').text(u.username),'&nbsp;',
																	$('<input>').attr('type','checkbox').attr((u.admin?'checked':'false'),'checked').change(function(){
																		pages = 'remadmin';
																		if(this.checked){
																			pages = 'addadmin';
																		}
																		network.getJSON('server.php?'+pages+'&id='+u.id.toString(10));
																	}),'&nbsp;',
																	$('<a>').text(locale.get('changePwd')).attr('href','http://pwd').click(function(e){
																		e.preventDefault();
																		page.hideAll();
																		$('#adminPage').empty().append(
																			$('<h2>').text(sprintf(locale.get('changePwdHeading'),u.username)),'<br>',
																			$('<form>').append(
																				locale.get('askPassword'),
																				$('<input>').attr({
																					type:'password',
																					name:'password'
																				}),'<br>',
																				locale.get('askPassword'),
																				$('<input>').attr({
																					type:'password',
																					name:'password2'
																				}),'<br>',
																				$('<input>').attr('type','submit').val(locale.get('changePwd'))
																			).submit(function(e){
																				e.preventDefault();
																				if(this.password.value == this.password2.value){
																					network.post('server.php?editPwd='+u.id.toString(10),{
																						password:this.password.value
																					},function(data){
																						if(data.success){
																							page.showAdminPage();
																						}else{
																							alert('error');
																						}
																					});
																				}else{
																					alert(locale.get('pwdDontMatch'));
																				}
																			})
																		);
																		page.show('adminPage');
																	}),
																	'<br>'
																];
															})
														);
														page.show('adminPage');
													});
												}
											}
										],function(a){
											if(a.ancor===false){
												return $('<li>').append(a.text);
											}
											return $('<li>').append(
													$('<a>')
														.attr('href','http://list')
														.append(a.text)
														.click(function(e){
															e.preventDefault();
															a.fn();
														})
												);
										})
								)
							);
							page.show('adminPage');
							hist.push('a');
						});
					}
				};
			return {
					init:function(){
						self.init();
					},
					hideAll:function(){
						self.hideAll();
					},
					show:function(s){
						self.show(s);
					},
					showAdminPage:function(){
						self.showAdminPage();
					}
				};
		})();
	$(document).ready(function(){
		locale.load('de',function(){
			page.init();
			hist.init();
			network.init();
			search.init();
			
			if(document.URL.split('?')[1]!==undefined){
				hist.load(document.URL.split('?')[1].split('#')[0]);
			}else{
				categories.load(1);
			}
		});
	});
})(jQuery);